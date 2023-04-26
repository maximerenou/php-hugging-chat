<?php

namespace MaximeRenou\HuggingChat;

class Client
{
    public function createConversation($identifiers = null)
    {
        return $this->resumeConversation($identifiers);
    }

    public function resumeConversation($identifiers)
    {
        return new Conversation($identifiers);
    }
}
