<?php

namespace Lugit;
use FastVolt\Helper\Markdown;

class RepoPage {
    private string $basePath;
    private array $excludedFolders;

    public function handle(): void {
        Config::init(dirname(__DIR__) . '/config.json');
        $this->basePath = Config::getRepositoriesPath();
        $this->excludedFolders = Config::getExcludedFolders();

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = preg_replace('#^/?repos/([^/]+)/?$#', '$1', $path);
        
        if (empty($path) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $path)) {
            $this->sendError(404, "Repository not found");
            return;
        }

        if (in_array($path, $this->excludedFolders)) {
            $this->sendError(404, "Repository not found");
            return;
        }

        $repoPath = $this->basePath . '/' . $path;
        if (!Utils::isGitRepo($repoPath)) {
            $this->sendError(404, "Repository not found");
            return;
        }

        $config = RepoConfig::load($repoPath);
        
        if (!$config->public) {
            $this->renderPrivate($path);
            return;
        }

        $this->renderPublic($path, $repoPath, $config);
    }

    private function renderPublic(string $name, string $repoPath, RepoConfig $config): void {
        $cloneUrl = $this->getCloneUrl($name);
        $readme = $this->getReadme($repoPath);
        $defaultBranch = $this->getDefaultBranch($repoPath);

        $readmeHtml = '';
        if ($readme) {
            /* $readmeHtml = Markdown::render($readme); */
			$readmeHtml = (new Markdown())->setContent($readme)->getHtml();
        }

        $usersList = '';
        if (!empty($config->allowedUsers)) {
            $usersList = '<div class="users-section">
                <h3>Contributors</h3>
                <ul class="user-list">' . implode("\n", array_map(fn($u) => "<li>$u</li>", $config->allowedUsers)) . '</ul>
            </div>';
        }

        $readmeSection = '';
        if ($readmeHtml) {
            $readmeSection = '<div class="readme-section">
                <div class="markdown-body">' . $readmeHtml . '</div>
            </div>';
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$name} - Git Repository</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            min-height: 100vh;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #30363d;
        }
        .repo-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 2.5rem;
            color: #f0f6fc;
            margin-bottom: 10px;
        }
        .clone-box {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 16px;
            margin: 20px 0;
        }
        .clone-box label {
            display: block;
            font-size: 0.875rem;
            color: #8b949e;
            margin-bottom: 8px;
        }
        .clone-url {
            display: flex;
            gap: 8px;
        }
        .clone-url input {
            flex: 1;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 10px 14px;
            color: #c9d1d9;
            font-family: 'SF Mono', Monaco, Consolas, monospace;
            font-size: 0.9rem;
        }
        .clone-url button {
            background: #238636;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            color: white;
            cursor: pointer;
            font-size: 0.875rem;
            transition: background 0.2s;
        }
        .clone-url button:hover {
            background: #2ea043;
        }
        .clone-url button:active {
            transform: scale(0.98);
        }
        .badge {
            display: inline-block;
            background: #238636;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .readme-section {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 40px;
            margin-top: 30px;
        }
        .markdown-body {
            color: #c9d1d9;
        }
        .markdown-body h1, .markdown-body h2, .markdown-body h3 {
            color: #f0f6fc;
            margin: 20px 0 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #30363d;
        }
        .markdown-body h1 { font-size: 2rem; }
        .markdown-body h2 { font-size: 1.5rem; }
        .markdown-body h3 { font-size: 1.25rem; }
        .markdown-body p { margin: 10px 0; }
        .markdown-body a { color: #58a6ff; text-decoration: none; }
        .markdown-body a:hover { text-decoration: underline; }
        .markdown-body code {
            background: #0d1117;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'SF Mono', Monaco, Consolas, monospace;
            font-size: 0.9em;
        }
        .markdown-body pre {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 16px;
            overflow-x: auto;
            margin: 15px 0;
        }
        .markdown-body pre code {
            background: none;
            padding: 0;
        }
        .markdown-body ul {
            list-style: disc;
            margin-left: 25px;
            margin: 10px 0;
        }
        .markdown-body li { margin: 5px 0; }
        .users-section {
            margin-top: 30px;
            padding: 20px;
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
        }
        .users-section h3 {
            color: #8b949e;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .user-list {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .user-list li {
            background: #0d1117;
            border: 1px solid #30363d;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
        }
        .copy-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #238636;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 0.875rem;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s;
            pointer-events: none;
        }
        .copy-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        @media (max-width: 600px) {
            h1 { font-size: 1.75rem; }
            .readme-section { padding: 20px; }
            .clone-url { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$name}</h1>
        </div>

        <div class="clone-box">
            <label>Clone this repository</label>
            <div class="clone-url">
                <input type="text" id="cloneUrl" value="{$cloneUrl}" readonly>
                <button onclick="copyUrl()">Copy</button>
            </div>
        </div>
        
        {$readmeSection}
        <!-- {$usersList} -->
    </div>

    <div class="copy-toast" id="toast">URL copied to clipboard!</div>

    <script>
        function copyUrl() {
            const input = document.getElementById('cloneUrl');
            input.select();
            document.execCommand('copy');
            
            const toast = document.getElementById('toast');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2000);
        }
    </script>
</body>
</html>
HTML;
    }

    private function renderPrivate(string $name): void {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$name} - Private Repository</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            padding: 40px;
        }
        .lock-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 1.5rem;
            color: #f0f6fc;
            margin-bottom: 10px;
        }
        p {
            color: #8b949e;
            font-size: 1rem;
        }
        .repo-name {
            color: #58a6ff;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="lock-icon">🔒</div>
        <h1>Private Repository</h1>
        <p>You don't have access to <span class="repo-name">{$name}</span></p>
    </div>
</body>
</html>
HTML;
    }

    private function getCloneUrl(string $name): string {
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? ($_SERVER['HTTPS'] ?? null ? 'https' : 'http');
        $hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $host = $hostHeader;
        $port = null;
        
        if (preg_match('/^(.+):(\d+)$/', $hostHeader, $matches)) {
            $host = $matches[1];
            $port = (int)$matches[2];
        }
        
        $defaultPort = $scheme === 'https' ? 443 : 80;
        
        $baseUrl = "$scheme://$host";
        if ($port && $port !== $defaultPort) {
            $baseUrl .= ":$port";
        }
        
        return "$baseUrl/$name";
    }

    private function getReadme(string $repoPath): ?string {
        $readmeNames = ['README.md', 'readme.md', 'README', 'readme', 'README.txt'];
        
        foreach ($readmeNames as $name) {
            $path = $repoPath . '/' . $name;
            if (file_exists($path)) {
                return file_get_contents($path);
            }
        }
        
        $result = Utils::runGit("git ls-tree -r HEAD", $repoPath);
        if ($result['exitCode'] !== 0) {
            return null;
        }
        
        $lines = explode("\n", trim($result['stdout']));
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 4);
            if (count($parts) >= 4) {
                $filename = basename($parts[3]);
                if (str_starts_with(strtolower($filename), 'readme')) {
                    $blobPath = $repoPath . '/objects/' . substr($parts[2], 0, 2) . '/' . substr($parts[2], 2);
                    if (file_exists($blobPath)) {
                        $content = gzuncompress(file_get_contents($blobPath));
                        if ($content && str_starts_with($content, 'blob ')) {
                            $content = preg_replace('/^blob \d+\x00/', '', $content);
                            return $content;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    private function getDefaultBranch(string $repoPath): string {
        $headFile = $repoPath . '/HEAD';
        if (file_exists($headFile)) {
            $content = trim(file_get_contents($headFile));
            if (str_starts_with($content, 'ref: ')) {
                return basename($content);
            }
        }
        return 'main';
    }

    private function sendError(int $code, string $message): void {
        http_response_code($code);
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {$code}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        h1 { font-size: 4rem; color: #f85149; }
        p { color: #8b949e; margin-top: 10px; }
    </style>
</head>
<body>
    <div>
        <h1>{$code}</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
