<?php

namespace MaximeRenou\HuggingChat;

class Client
{
    public function createConversation($model = null)
    {
        return new Conversation(null, $model);
    }

    public function resumeConversation($identifiers)
    {
        return new Conversation($identifiers);
    }
}
