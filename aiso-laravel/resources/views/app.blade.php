<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AISO</title>
    <meta name="description" content="Operations dependency engine and task assignment (algorithm or LLM)">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&family=Geist+Mono:wght@100..900&family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/js/main.tsx'])
</head>
<body class="min-h-screen">
    <div id="root"></div>
</body>
</html>
