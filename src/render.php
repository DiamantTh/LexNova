<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function render_text(string $content): string
{
    return nl2br(h($content));
}
