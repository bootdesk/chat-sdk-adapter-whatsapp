<?php

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Divider;
use BootDesk\ChatSDK\Core\Cards\Image;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\LinkButton;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link as CommonMarkLink;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text as CommonMarkText;
use League\CommonMark\Parser\MarkdownParser;

class WhatsAppCards
{
    private const MAX_REPLY_BUTTONS = 3;

    private const MAX_BUTTON_TITLE_LENGTH = 20;

    private const CALLBACK_DATA_PREFIX = 'chat:';

    public static function toInteractiveMessage(Card $card): ?array
    {
        $buttons = $card->getButtons();

        if ($buttons === [] || count($buttons) > self::MAX_REPLY_BUTTONS) {
            return null;
        }

        $replyButtons = [];
        foreach ($buttons as $button) {
            if (strlen($button->label) > self::MAX_BUTTON_TITLE_LENGTH) {
                return null;
            }

            $replyButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => self::encodeCallbackData($button->actionId),
                    'title' => $button->label,
                ],
            ];
        }

        $body = self::buildBodyText($card);

        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body ?: 'Please choose an option'],
            'action' => ['buttons' => $replyButtons],
        ];

        if ($card->getHeader() !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $card->getHeader()];
        }

        return $interactive;
    }

    public static function cardToText(Card $card): string
    {
        $lines = [];

        if ($card->getImageUrl() !== null) {
            $lines[] = $card->getImageUrl();
            $lines[] = '';
        }

        if ($card->getHeader() !== null) {
            $lines[] = '*'.$card->getHeader().'*';
        }

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $lines[] = $child->content;
            } elseif ($child instanceof Divider) {
                $lines[] = '---';
            } elseif ($child instanceof Image) {
                $lines[] = $child->alt !== '' ? "{$child->alt}: {$child->url}" : $child->url;
            } elseif ($child instanceof Link) {
                $lines[] = "[{$child->label}]({$child->url})";
            } elseif ($child instanceof Table) {
                $lines[] = self::renderTableAsText($child);
            } elseif ($child instanceof LinkButton) {
                $lines[] = "[{$child->label}]({$child->url})";
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $lines[] = self::markdownToWhatsApp($section->getText());
            }

            foreach ($section->getFields() as $label => $value) {
                $lines[] = "*{$label}:* {$value}";
            }
        }

        $allButtons = array_merge($card->getButtons(), $card->getLinkButtons());
        if ($lines !== [] && $allButtons !== []) {
            $lines[] = '';
            $buttonTexts = array_map(fn ($b): string => "[{$b->label}]", $allButtons);
            $lines[] = implode(' | ', $buttonTexts);
        }

        return implode("\n", $lines);
    }

    public static function encodeCallbackData(string $actionId, ?string $value = null): string
    {
        $payload = ['a' => $actionId];
        if ($value !== null) {
            $payload['v'] = $value;
        }

        return self::CALLBACK_DATA_PREFIX.json_encode($payload);
    }

    public static function decodeCallbackData(?string $data): array
    {
        if ($data === null || $data === '') {
            return ['actionId' => 'whatsapp_callback', 'value' => null];
        }

        if (! str_starts_with($data, self::CALLBACK_DATA_PREFIX)) {
            return ['actionId' => $data, 'value' => $data];
        }

        $json = substr($data, strlen(self::CALLBACK_DATA_PREFIX));
        $decoded = json_decode($json, true);

        if (is_array($decoded) && isset($decoded['a'])) {
            return [
                'actionId' => $decoded['a'],
                'value' => $decoded['v'] ?? null,
            ];
        }

        return ['actionId' => $data, 'value' => $data];
    }

    private static function buildBodyText(Card $card): string
    {
        $parts = [];

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $parts[] = $child->content;
            } elseif ($child instanceof Image) {
                $parts[] = $child->alt !== '' ? "{$child->alt}: {$child->url}" : $child->url;
            } elseif ($child instanceof Link) {
                $parts[] = "{$child->label}: {$child->url}";
            } elseif ($child instanceof Table) {
                $parts[] = self::renderTableAsText($child);
            } elseif ($child instanceof LinkButton) {
                $parts[] = "[{$child->label}]({$child->url})";
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $parts[] = self::markdownToWhatsApp($section->getText());
            }

            foreach ($section->getFields() as $label => $value) {
                $parts[] = "{$label}: {$value}";
            }
        }

        return implode("\n", $parts);
    }

    private static function markdownToWhatsApp(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $parser = new MarkdownParser($environment);
        $ast = $parser->parse($markdown);

        $walker = $ast->walker();
        $result = '';

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering()) {
                if ($node instanceof Strong) {
                    $result .= '*';
                } elseif ($node instanceof Emphasis) {
                    $result .= '_';
                } elseif ($node instanceof Code) {
                    $result .= '`'.$node->getLiteral().'`';
                } elseif ($node instanceof CommonMarkText) {
                    $result .= $node->getLiteral();
                }
            } elseif ($node instanceof Strong) {
                $result .= '*';
            } elseif ($node instanceof Emphasis) {
                $result .= '_';
            } elseif ($node instanceof CommonMarkLink) {
                $result .= ' ('.$node->getUrl().')';
            } elseif ($node instanceof Paragraph) {
                $result .= "\n";
            }
        }

        return trim($result);
    }

    private static function renderTableAsText(Table $table): string
    {
        $lines = [];
        $lines[] = '| '.implode(' | ', $table->headers).' |';
        $separators = [];
        foreach (array_keys($table->headers) as $i) {
            $align = $table->align[$i] ?? null;
            $separators[] = match ($align?->value) {
                'center' => ':---:',
                'right' => '---:',
                default => '---',
            };
        }
        $lines[] = '| '.implode(' | ', $separators).' |';
        foreach ($table->rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines);
    }
}
