<?php

declare(strict_types=1);

/**
 * Shared Tailwind config and base styles for the application.
 */
?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    brand: {
                        50:  '#eff6ff',
                        100: '#dbeafe',
                        200: '#bfdbfe',
                        500: '#3b82f6',
                        600: '#2563eb',
                        700: '#1d4ed8',
                        800: '#1e40af',
                        900: '#1e3a5f',
                    }
                },
                fontFamily: {
                    sans: ['Inter', 'system-ui', 'sans-serif'],
                }
            }
        }
    }
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    [v-cloak] { display: none; }
    .nav-item.active { background-color: #2563eb; color: #fff; }
    .row-critical { background-color: #fef2f2; }
    .row-warning  { background-color: #fffbeb; }
    .row-expired  { background-color: #fee2e2; }
</style>
