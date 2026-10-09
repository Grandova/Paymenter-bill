<style>
    :root {
        /* Branding Colors (Light) */
        --color-primary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('primary', '222 38% 15%'))) }};
        --color-secondary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('secondary', '237 33% 60%'))) }};

        /* Neutral Colors - Borders, Accents... (Light) */
        --color-neutral: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('neutral', '220 13% 89%'))) }};

        /* Text Colors (Light) */
        --color-base: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('base', '222 35% 15%'))) }};
        --color-muted: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('muted', '218 11% 45%'))) }};
        --color-inverted: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('inverted', '100 100% 100%'))) }};

        /* State Colors */
        --color-success: 142 71% 45%;
        --color-error: 0 75% 60%;
        --color-warning: 25 95% 53%;
        --color-inactive: 0 0% 63%;
        --color-info: 210 100% 60%;

        /* Background Colors (Light) */
        --color-background: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('background', '220 14% 96%'))) }};
        --color-background-secondary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('background-secondary', '0 0% 100%'))) }};
    }

    .dark {
        /* Branding Colors (Dark) */
        --color-primary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-primary', '229 100% 64%'))) }};
        --color-secondary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-secondary', '237 33% 60%'))) }};

        /* Neutral Colors - Borders, Accents... (Dark) */
        --color-neutral: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-neutral', '222 16% 24%'))) }};

        /* Text Colors (Dark) */
        --color-base: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-base', '220 18% 94%'))) }};
        --color-muted: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-muted', '218 14% 65%'))) }};
        --color-inverted: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-inverted', '220 14% 60%'))) }};

        /* Background Colors (Dark) */
        --color-background: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-background', '224 25% 9%'))) }};
        --color-background-secondary: {{ str_replace(',', '', preg_replace('/^hsl\((.+)\)$/', '$1', theme('dark-background-secondary', '224 22% 13%'))) }};
    }
</style>