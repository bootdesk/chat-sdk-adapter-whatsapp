<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\WhatsApp\WhatsAppAdapter;

AdapterRegistry::register('whatsapp', WhatsAppAdapter::class);
