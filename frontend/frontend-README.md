# frontend/ — React TypeScript portfolio

This folder will host the React frontend that serves as the technical portfolio. It consumes the C# API exclusively and has no direct knowledge of WordPress.

The stack is deliberately minimal and modern:
- **React 18** with **TypeScript**
- **Vite** as bundler
- **React Router v6** for navigation
- **TanStack Query** for server state and caching
- **Tailwind CSS** for styling
- No global state manager (not needed at this scale)

Technical enrichments like Mermaid diagrams and KaTeX formulas are loaded on-demand per article, based on flags in the Markdown frontmatter.

**Current status**: not yet implemented. The Vite project will be created during Phase 7 of the project roadmap.

For complete architectural details, see [`../docs/architecture/03-frontend-react.md`](../docs/architecture/03-frontend-react.md).
