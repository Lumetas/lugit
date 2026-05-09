<?php
namespace Lugit;
class Markdown {
    public static function render(string $markdown): string {
        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
        
        $html = preg_replace('/```(\w*)\n(.*?)```/s', '<pre><code class="language-$1">$2</code></pre>', $html);
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
        
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
        
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $html);
        $html = preg_replace('/_([^_]+)_/', '<em>$1</em>', $html);
        
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);
        
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        
        $lines = explode("\n", $html);
        $result = [];
        $in_pre = false;
        
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '<pre>')) $in_pre = true;
            if (str_starts_with(trim($line), '</pre>')) $in_pre = false;
            
            if (!$in_pre && trim($line) !== '' && !str_starts_with($line, '<') && !str_starts_with($line, '</')) {
                $result[] = '<p>' . $line . '</p>';
            } else {
                $result[] = $line;
            }
        }
        
        return implode("\n", $result);
    }
}
