<?php

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class WhatsAppFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        $markdown = $this->fromWhatsAppFormat($text);

        return $this->parseMarkdown($markdown);
    }

    public function fromAst(Document $ast): string
    {
        $markdown = $this->renderMarkdown($ast);

        return $this->toWhatsAppFormat($markdown);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return (string) $message->content;
    }

    private function toWhatsAppFormat(string $text): string
    {
        // **bold** -> *bold*
        $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
        // ~~strikethrough~~ -> ~strikethrough~
        $text = preg_replace('/~~(.+?)~~/', '~$1~', $text);

        return $text;
    }

    private function fromWhatsAppFormat(string $text): string
    {
        // *bold* -> **bold** (single * not preceded/followed by *)
        $text = preg_replace('/(?<!\*)\*(?!\*)([^\n*]+?)(?<!\*)\*(?!\*)/', '**$1**', $text);
        // ~strike~ -> ~~strike~~
        $text = preg_replace('/(?<!~)~(?!~)([^\n~]+?)(?<!~)~(?!~)/', '~~$1~~', $text);

        return $text;
    }
}
