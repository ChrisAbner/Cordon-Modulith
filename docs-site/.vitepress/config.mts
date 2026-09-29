import { defineConfig } from 'vitepress'

export default defineConfig({
    title: 'Cordon Modulith',
    description: 'Verifiable boundaries between the modules of a Laravel application.',
    base: '/Cordon-Modulith/',
    cleanUrls: true,
    lastUpdated: true,
    themeConfig: {
        nav: [
            { text: 'Guide', link: '/guide/introduction' },
            { text: 'Recipes', link: '/recipes/expose-a-service' },
            { text: 'Comparison', link: '/comparison' },
            { text: 'Changelog', link: 'https://github.com/ChrisAbner/Cordon-Modulith/blob/main/CHANGELOG.md' },
        ],
        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Introduction', link: '/guide/introduction' },
                    { text: 'Installation', link: '/guide/installation' },
                    { text: 'Adopting in an existing project', link: '/guide/baseline' },
                ],
            },
            {
                text: 'Concepts',
                items: [
                    { text: 'The public API of a module', link: '/guide/public-api' },
                    { text: 'Rules', link: '/guide/rules' },
                    { text: 'Configuration', link: '/guide/configuration' },
                ],
            },
            {
                text: 'Integrations',
                items: [
                    { text: 'Continuous integration', link: '/guide/ci' },
                    { text: 'Pest', link: '/guide/pest' },
                    { text: 'PHPStan', link: '/guide/phpstan' },
                    { text: 'AI coding agents', link: '/guide/ai-agents' },
                ],
            },
            {
                text: 'Going further',
                items: [
                    { text: 'Living documentation', link: '/guide/living-documentation' },
                    { text: 'Custom rules', link: '/guide/custom-rules' },
                ],
            },
            {
                text: 'Recipes',
                items: [
                    { text: 'Expose a service to other modules', link: '/recipes/expose-a-service' },
                    { text: 'Break a cycle with an event', link: '/recipes/break-a-cycle' },
                    { text: 'Replace a cross-module Eloquent relation', link: '/recipes/cross-module-relations' },
                ],
            },
            {
                text: 'More',
                items: [
                    { text: 'Comparison', link: '/comparison' },
                    { text: 'FAQ', link: '/faq' },
                ],
            },
        ],
        socialLinks: [{ icon: 'github', link: 'https://github.com/ChrisAbner/Cordon-Modulith' }],
        editLink: {
            pattern: 'https://github.com/ChrisAbner/Cordon-Modulith/edit/main/docs-site/:path',
        },
        search: { provider: 'local' },
        footer: {
            message: 'MIT licensed. A community project, not affiliated with or endorsed by Laravel.',
        },
    },
})
