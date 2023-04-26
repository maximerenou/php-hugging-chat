<?php

namespace MaximeRenou\HuggingChat;

class Prompt
{
    public $text;
    
    // Model parameters
    public $cache = false;
    public $max_new_tokens = 1024;
    public $repetition_penalty = 1.2;
    public $return_full_text = false;
    public $temperature = 0.9;
    public $top_k = 50;
    public $top_p = 0.95;
    public $truncate = 1024;
    public $watermark = false;

    public function __construct($text)
    {
        $this->text = $text;
    }
}