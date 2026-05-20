<?php
declare(strict_types=1);

namespace App\Lib\Tools;

use Cake\Core\App;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\View\View;

/**
 * Renders both html and text variants of an email template.
 *
 * The html variant is optional (returns `null` when no file exists);
 * the text variant is required and a missing file raises
 * SendEmailException. Subject can be exposed by the template via
 * `$this->set('subject', ...)`.
 */
class EmailRenderer
{
    /**
     * Render an email template into html, text, and subject parts.
     *
     * @param string $name Template stem (without `.php` and without the `html/` or `text/` prefix).
     * @param array<string, mixed> $vars View variables exposed to the template.
     * @return array{html: ?string, text: string, subject: ?string}
     * @throws \App\Lib\Tools\SendEmailException When the text variant is missing.
     */
    public function render(string $name, array $vars): array
    {
        $templatesDir = $this->templatesDir();

        $textPath = $templatesDir . 'email' . DS . 'text' . DS . $name . '.php';
        if (!is_file($textPath)) {
            throw new SendEmailException(sprintf(
                'Email template "%s" is missing its required text variant (expected at %s).',
                $name,
                $textPath
            ));
        }

        $textView = $this->buildView('text', $vars);
        $text = $textView->render($name);
        $subject = $textView->get('subject');
        if ($subject !== null) {
            $subject = (string)$subject;
        }

        $html = null;
        $htmlPath = $templatesDir . 'email' . DS . 'html' . DS . $name . '.php';
        if (is_file($htmlPath)) {
            $htmlView = $this->buildView('html', $vars);
            $html = $htmlView->render($name);
            if ($subject === null) {
                $htmlSubject = $htmlView->get('subject');
                if ($htmlSubject !== null) {
                    $subject = (string)$htmlSubject;
                }
            }
        }

        return [
            'html' => $html,
            'text' => $text,
            'subject' => $subject,
        ];
    }

    /**
     * Build a View configured to look in `email/<variant>/` for both template and layout.
     *
     * @param string $variant Either `html` or `text`.
     * @param array<string, mixed> $vars View variables.
     * @return \Cake\View\View
     */
    protected function buildView(string $variant, array $vars): View
    {
        $view = new View(new ServerRequest(), new Response());
        $view->setTemplatePath('email' . DS . $variant);
        $view->setLayoutPath('email' . DS . $variant);
        $view->setLayout('default');
        $view->set($vars);

        return $view;
    }

    /**
     * Resolve the project's primary templates directory (with trailing DS).
     *
     * @return string
     */
    protected function templatesDir(): string
    {
        $paths = App::path('templates');
        if (!empty($paths)) {
            return rtrim((string)$paths[0], DS) . DS;
        }

        return ROOT . DS . 'templates' . DS;
    }
}
