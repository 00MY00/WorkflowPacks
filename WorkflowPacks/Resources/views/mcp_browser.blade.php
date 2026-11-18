{{-- The address of the connector opened in a browser: what it is and where it goes (nothing about the agent). --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('workflowpacks::ai.mcp_browser_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;
            font: 15px/1.5 -apple-system, "Segoe UI", Roboto, sans-serif; background: #f6f7fb; color: #1f2937; }
        main { max-width: 520px; background: #fff; border: 1px solid #dfe3e8; border-radius: 14px; padding: 24px; }
        h1 { margin: 0 0 8px; font-size: 19px; color: #312e81; }
        p { margin: 8px 0; }
        .ok { color: #047857; font-weight: 600; }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #e2e8f0; } main { background: #1e293b; border-color: #334155; } h1 { color: #c7d2fe; } .ok { color: #6ee7b7; } }
    </style>
</head>
<body>
<main>
    <h1>{{ __('workflowpacks::ai.mcp_browser_title') }}</h1>
    <p class="ok">{{ __('workflowpacks::ai.mcp_browser_ok') }}</p>
    <p>{{ __('workflowpacks::ai.mcp_browser_text') }}</p>
</main>
</body>
</html>
