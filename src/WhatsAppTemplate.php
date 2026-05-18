<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\WhatsApp;

use BootDesk\ChatSDK\Core\Template;

class WhatsAppTemplate extends Template
{
    private array $bodyParams = [];

    private ?array $headerParam = null;

    private ?string $headerType = null;

    private ?array $buttonParams = null;

    private ?string $parameterFormat = null;

    public static function create(string $name, string $language): self
    {
        return new self($name, $language);
    }

    public function positional(): self
    {
        $this->parameterFormat = 'positional';

        return $this;
    }

    public function named(): self
    {
        $this->parameterFormat = 'named';

        return $this;
    }

    public function bodyParam(string $text, ?string $parameterName = null): self
    {
        $param = ['type' => 'text', 'text' => $text];
        if ($parameterName !== null) {
            $param['parameter_name'] = $parameterName;
        }
        $this->bodyParams[] = $param;

        return $this;
    }

    public function headerImage(string $link): self
    {
        $this->headerType = 'image';
        $this->headerParam = ['type' => 'image', 'image' => ['link' => $link]];

        return $this;
    }

    public function headerVideo(string $link): self
    {
        $this->headerType = 'video';
        $this->headerParam = ['type' => 'video', 'video' => ['link' => $link]];

        return $this;
    }

    public function headerDocument(string $link, ?string $filename = null): self
    {
        $doc = ['link' => $link];
        if ($filename !== null) {
            $doc['filename'] = $filename;
        }
        $this->headerType = 'document';
        $this->headerParam = ['type' => 'document', 'document' => $doc];

        return $this;
    }

    public function headerText(string $text): self
    {
        $this->headerType = 'text';
        $this->headerParam = ['type' => 'text', 'text' => $text];

        return $this;
    }

    public function buttonParam(string $label, string $payload = '', string $subtype = 'quick_reply'): self
    {
        $this->buttonParams[] = ['type' => $subtype, 'payload' => $payload !== '' ? $payload : $label, 'label' => $label];

        return $this;
    }

    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }

    public function getHeaderParam(): ?array
    {
        return $this->headerParam;
    }

    public function getHeaderType(): ?string
    {
        return $this->headerType;
    }

    public function getButtonParams(): ?array
    {
        return $this->buttonParams;
    }

    public function toWhatsApp(): array
    {
        $components = [];

        if ($this->headerParam !== null) {
            $components[] = [
                'type' => 'header',
                'parameters' => [$this->headerParam],
            ];
        }

        if ($this->bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->bodyParams,
            ];
        }

        if ($this->buttonParams !== null) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => 0,
                'parameters' => $this->buttonParams,
            ];
        }

        $template = [
            'name' => $this->getName(),
            'language' => ['code' => $this->getLanguage()],
            'components' => $components,
        ];

        if ($this->parameterFormat !== null) {
            $template['parameter_format'] = $this->parameterFormat;
        }

        return [
            'type' => 'template',
            'template' => $template,
        ];
    }

    public function __toString(): string
    {
        $lines = [];

        if ($this->bodyParams !== []) {
            $bodyTexts = array_map(fn (array $p) => $p['text'] ?? '', $this->bodyParams);
            $lines[] = '**'.implode(' | ', $bodyTexts).'**';
        }

        if ($this->headerParam !== null) {
            $headerText = match ($this->headerType) {
                'image' => '🖼 '.($this->headerParam['image']['link'] ?? ''),
                'video' => '🎬 '.($this->headerParam['video']['link'] ?? ''),
                'document' => '📄 '.($this->headerParam['document']['link'] ?? ''),
                'text' => $this->headerParam['text'] ?? '',
                default => '',
            };
            if ($headerText !== '') {
                $lines[] = $headerText;
            }
        }

        if ($this->buttonParams !== null) {
            $lines[] = '';
            foreach ($this->buttonParams as $i => $p) {
                $lines[] = ($i + 1).'. ['.($p['label'] ?? '').']';
            }
        }

        return implode("\n", $lines);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'language' => $this->getLanguage(),
            'body_params' => array_map(fn (array $p) => $p['text'] ?? '', $this->bodyParams),
        ];
    }
}
