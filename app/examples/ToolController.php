<?php

declare(strict_types=1);

namespace App\Examples;

use Base;

/**
 * Example controller for a tool that needs server-side preparation or actions.
 * View-only tools do not need a controller.
 */
final class ToolController
{
    public function index(Base $f3, array $params = []): void
    {
        $f3->set('TOOL_DATA', [
            'value' => '',
            'result' => null,
        ]);
    }

    public function status(Base $f3, array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
    }

    public function run(Base $f3, array $params = []): void
    {
        $value = trim((string) $f3->get('POST.value'));

        $f3->set('TOOL_DATA', [
            'value' => $value,
            'result' => strtoupper($value),
        ]);
    }
}
