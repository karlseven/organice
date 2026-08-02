<?php
declare(strict_types=1);

namespace Core;

final class View
{
    /** Render app/Views/<template>.php inside the layout. */
    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/Views/' . $template . '.php';
        $content = ob_get_clean();
        require APP_PATH . '/Views/layout.php';
    }

    /** Render without the chrome — the editor uses the full viewport. */
    public static function bare(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_PATH . '/Views/' . $template . '.php';
    }

    /** Render a partial to a string. */
    public static function partial(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/Views/' . $template . '.php';
        return (string)ob_get_clean();
    }
}
