<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaEmphasisRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaHeadingRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaImageRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaLinkRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaListItemRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaStrikethroughRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaStrongRenderer;
use BootDesk\ChatSDK\Core\Markdown\Renderer\Meta\MetaThematicBreakRenderer;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Node\Block\Document;

class WhatsAppFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        $text = preg_replace('/(?<!~)~(?!~)([^~\n]+?)(?<!~)~(?!~)/', '~~$1~~', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+?)(?<!\*)\*(?!\*)/', '**$1**', $text);
        $text = preg_replace('/(?<!_)_(?!_)([^_\n]+?)(?<!_)_(?!_)/', '*$1*', $text);

        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return $this->fromMarkdown((string) $message->content);
    }

    protected function registerRenderers(): void
    {
        parent::registerRenderers();

        $this->addRenderer(Strong::class, new MetaStrongRenderer, 10);
        $this->addRenderer(Emphasis::class, new MetaEmphasisRenderer, 10);
        $this->addRenderer(Strikethrough::class, new MetaStrikethroughRenderer, 10);
        $this->addRenderer(Link::class, new MetaLinkRenderer, 10);
        $this->addRenderer(Image::class, new MetaImageRenderer, 10);
        $this->addRenderer(Heading::class, new MetaHeadingRenderer, 10);
        $this->addRenderer(ListItem::class, new MetaListItemRenderer, 10);
        $this->addRenderer(ThematicBreak::class, new MetaThematicBreakRenderer, 10);
    }
}
