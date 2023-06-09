<?php

namespace MaximeRenou\HuggingChat;

use EventSource\Event;
use EventSource\EventSource;

class Conversation
{
    const END_CHAR = '<|endoftext|>';
    const DEFAULT_MODEL = 'OpenAssistant/oasst-sft-6-llama-30b-xor';

    // Conversation IDs
    public $id;
    public $cookie;

    // Conversation data
    protected $current_started;
    protected $current_text;

    public function __construct($identifiers = null, $model = null)
    {
        if (is_null($model)) {
            $model = self::DEFAULT_MODEL;
        }

        if (is_array($identifiers) && ! empty($identifiers['cookie']))
            $this->cookie = $identifiers['cookie'];

        if (! is_array($identifiers))
            $identifiers = $this->initConversation($model);

        $this->id = $identifiers['id'];
        $this->cookie = $identifiers['cookie'];
    }

    public function getIdentifiers()
    {
        return [
            'id' => $this->id,
            'cookie' => $this->cookie
        ];
    }

    public function initConversation($model)
    {
        $headers = [
            'method: POST',
            'accept: application/json',
            'accept-language: en-US,en;q=0.9',
            'accept-encoding: gzip, deflate, br',
            "referer: https://huggingface.co/chat/",
            "origin: https://huggingface.co",
            'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36',
        ];

        if (! empty($this->cookie)) {
            $headers[] = "cookie: hf-chat={$this->cookie}";
        } else {
            list($data, $request, $url, $cookies) = Tools::request("https://huggingface.co/chat", [], '', true);

            if (! empty($cookies['hf-chat'])) {
                $this->cookie = $cookies['hf-chat'];
                $headers[] = "cookie: hf-chat={$this->cookie}";
            }

            // Ethics modal
            $data = [
                'ethicsModalAccepted' => true,
                'ethicsModalAcceptedAt' => null,
                'shareConversationsWithModelAuthors' => false,
                'activeModel' => $model
            ];

            Tools::request("https://huggingface.co/chat/settings", array_merge($headers, [
                'x-sveltekit-action: true',
                'content-type: multipart/form-data'
            ]), $data);
        }

        // Init conversation
        $data = json_encode(['model' => $model]);
        $data = Tools::request("https://huggingface.co/chat/conversation", array_merge($headers, ['content-type: application/json']), $data);
        $data = json_decode($data, true);

        if (! is_array($data) || empty($data['conversationId']))
            throw new \Exception("Failed to init conversation");

        return [
            'id' => $data['conversationId'],
            'cookie' => $this->cookie
        ];
    }

    public function ask(Prompt $message, $callback = null)
    {
        $this->current_text = '';

        $es = new EventSource("https://huggingface.co/chat/conversation/{$this->id}");

        $data = [
            'inputs' => $message->text,
            'options' => [
                'use_cache' => $message->cache
            ],
            'parameters' => [
                'max_new_tokens' => $message->max_new_tokens,
                'repetition_penalty' => $message->repetition_penalty,
                'return_full_text' => $message->return_full_text,
                'stop' => [self::END_CHAR],
                'temperature' => $message->temperature,
                'top_k' => $message->top_k,
                'top_p' => $message->top_p,
                'truncate' => $message->truncate,
                'watermark' => $message->watermark,
            ],
            'stream' => true
        ];

        $es->setCurlOptions([
            CURLOPT_HTTPHEADER => [
                'method: POST',
                'accept: */*',
                "referer: https://huggingface.co/chat/conversation/{$this->id}",
                'content-type: application/json',
                "cookie: hf-chat={$this->cookie}"
            ],
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $es->onMessage(function (Event $event) use (&$callback) {
            $message = $this->handlePacket($event->data);

            if ($message === false)
                return;

            $tokens = $message['text'];

            if ($message['final']) {
                $offset = strlen($this->current_text);
                $this->current_text = $tokens;
                $tokens = substr($tokens, $offset);
            }
            else {
                $this->current_text .= $tokens;
            }

            if (($pos = strpos($this->current_text, self::END_CHAR)) !== false) {
                $this->current_text = substr($this->current_text, 0, $pos);
            }

            if (! is_null($callback)) {
                $callback($this->current_text, $tokens);
            }
        });

        @$es->connect();

        return $this->current_text;
    }

    protected function handlePacket($raw)
    {
        $data = json_decode($raw, true);

        if (! $data) {
            return false;
        }

        if (empty($data['token'])) {
            Tools::debug("Drop: $raw");

            if (! empty($data['error'])) {
                throw new \Exception($data['error']);
            }

            return false;
        }

        $text = $data['token']['special'] ? $data['generated_text'] : $data['token']['text'];

        if (($pos = strpos($text, self::END_CHAR)) !== false) {
            $text = substr($text, 0, $pos);
        }

        return [
            'text' => $text,
            'final' => $data['token']['special']
        ];
    }

    public function getSummary()
    {
        $headers = [
            'method: POST',
            'accept: application/json',
            "referer: https://huggingface.co/chat",
            'content-type: application/json',
            "cookie: hf-chat={$this->cookie}"
        ];

        $data = Tools::request("https://huggingface.co/chat/conversation/{$this->id}/summarize", $headers, '', false);
        $data = json_decode($data, true);

        if (! is_array($data) || empty($data['title']))
            throw new \Exception("Failed to get conversation's summary");

        return trim($data['title'], '"');
    }

    public function enableSharing()
    {
        return $this->withSettings([
            'shareConversationsWithModelAuthors' => true
        ]);
    }

    public function disableSharing()
    {
        return $this->withSettings([
            'shareConversationsWithModelAuthors' => false
        ]);
    }

    public function withSettings($settings)
    {
        $headers = [
            'method: PATCH',
            'accept: application/json',
            "origin: https://huggingface.co",
            "referer: https://huggingface.co/chat/privacy",
            'content-type: application/json',
            "cookie: hf-chat={$this->cookie}"
        ];

        $data = json_encode($settings);

        Tools::request("https://huggingface.co/chat/settings", $headers, $data);

        return $this;
    }

    public function delete()
    {
        $headers = [
            'method: DELETE',
            'accept: application/json',
            "referer: https://huggingface.co/chat",
            'content-type: application/json',
            "cookie: hf-chat={$this->cookie}"
        ];

        Tools::request("https://huggingface.co/chat/conversation/{$this->id}", $headers, '');
    }
}
