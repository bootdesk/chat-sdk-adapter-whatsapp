<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;

class WhatsAppFormatConverter extends BaseFormatConverter
{
    private MarkdownParser $whatsappParser;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new StrikethroughExtension);
        $this->whatsappParser = new MarkdownParser($environment);
    }

    protected function parseMarkdown(string $markdown): Document
    {
        return $this->whatsappParser->parse($markdown);
    }

    public function toAst(string $text): Document
    {
        $text = preg_replace('/(?<!~)~(?!~)([^~\n]+?)(?<!~)~(?!~)/', '~~$1~~', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+?)(?<!\*)\*(?!\*)/', '**$1**', $text);
        $text = preg_replace('/(?<!_)_(?!_)([^_\n]+?)(?<!_)_(?!_)/', '*$1*', $text);

        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderText($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return $this->fromMarkdown((string) $message->content);
    }

    private function renderText(Document $ast): string
    {
        $walker = $ast->walker();
        $output = '';
        $stack = [];
        $listType = null;
        $orderedCounter = 0;
        $inBlockQuote = false;

        while ($event = $walker->next()) {
            $node = $event->getNode();
            $entering = $event->isEntering();

            if (! $entering) {
                if ($stack === []) {
                    continue;
                }

                $closing = array_pop($stack);

                if ($closing !== null) {
                    $output .= $closing;
                }

                if ($node instanceof BlockQuote) {
                    $inBlockQuote = false;
                }

                continue;
            }

            if ($node instanceof Text) {
                $output .= $node->getLiteral();
            } elseif ($node instanceof Newline) {
                $output .= "\n";
            } elseif ($node instanceof Paragraph) {
                $stack[] = "\n";
            } elseif ($node instanceof Heading) {
                $stack[] = "\n";
            } elseif ($node instanceof ListBlock) {
                $listType = $node->getListData()->type;
                $orderedCounter = $listType === ListBlock::TYPE_ORDERED
                    ? ($node->getListData()->start ?? 1)
                    : 0;
                $stack[] = "\n";
            } elseif ($node instanceof ListItem) {
                if ($listType === ListBlock::TYPE_BULLET) {
                    $output .= '* ';
                } elseif ($listType === ListBlock::TYPE_ORDERED) {
                    $output .= $orderedCounter.'. ';
                    $orderedCounter++;
                }
                $stack[] = "\n";
            } elseif ($node instanceof BlockQuote) {
                $inBlockQuote = true;
                $output .= '>';
                $stack[] = "\n";
            } elseif ($node instanceof Link) {
                $stack[] = null;
            } elseif ($node instanceof FencedCode) {
                $output .= "```\n".$node->getLiteral()."\n```";
            } elseif ($node instanceof Code) {
                $output .= '`'.$node->getLiteral().'`';
            } elseif ($node instanceof Strikethrough) {
                $output .= '~';
                $stack[] = '~';
            } elseif (get_class($node) === 'League\CommonMark\Extension\CommonMark\Node\Inline\Strong') {
                $output .= '*';
                $stack[] = '*';
            } elseif (get_class($node) === 'League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis') {
                $output .= '_';
                $stack[] = '_';
            } else {
                $stack[] = null;
            }
        }

        return trim($output);
    }
}
