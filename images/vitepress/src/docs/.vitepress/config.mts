import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'My Docs',
  description: 'A VitePress documentation site',

  themeConfig: {
    nav: [
      { text: 'Home', link: '/' },
      { text: 'Guide', link: '/guide/getting-started' },
    ],

    sidebar: [
      {
        text: 'Guide',
        items: [
          { text: 'Getting Started', link: '/guide/getting-started' },
          { text: 'Configuration', link: '/guide/configuration' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/your-org/your-repo' },
    ],
  },
})
