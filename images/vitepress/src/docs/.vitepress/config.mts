import { defineConfig } from 'vitepress'
import { generateSidebar } from 'vitepress-sidebar'

export default defineConfig({
  title: 'My Docs',
  description: 'A VitePress documentation site',

  themeConfig: {
    nav: [
      { text: 'Home', link: '/' },
    ],

    sidebar: generateSidebar({
      documentRootPath: 'docs',
      collapsed: false,
      capitalizeFirst: true,
      useTitleFromFrontmatter: true,
      useTitleFromFileHeading: true,
      excludePattern: ['index.md'],
    }),

    socialLinks: [
      { icon: 'github', link: 'https://github.com/your-org/your-repo' },
    ],
  },
})
