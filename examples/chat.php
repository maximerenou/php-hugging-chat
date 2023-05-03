<?php
require __DIR__ . '/../vendor/autoload.php';

\MaximeRenou\HuggingChat\Tools::$debug = false; // Set true for verbose

$ai = new \MaximeRenou\HuggingChat\Client();

$conversation = $ai->createConversation()->disableSharing();

echo 'Type "q" to quit' . PHP_EOL;

while (true) {
    echo PHP_EOL . "> ";

    $text = rtrim(fgets(STDIN));

    if ($text == 'q')
        break;

    $prompt = new \MaximeRenou\HuggingChat\Prompt($text);

    echo "-";

    try {
        $full_answer = $conversation->ask($prompt, function ($answer, $tokens) {
            echo $tokens;
        });
    }
    catch (\Exception $exception) {
        echo " Sorry, something went wrong: {$exception->getMessage()}.";
    }
}

exit(0);
