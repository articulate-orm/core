// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
	site: 'https://articulate-orm.github.io',
	base: '/core',
	integrations: [
		starlight({
			title: 'Articulate',
			description:
				'Articulate is a PHP 8.4+ ORM that lets several narrow entity classes map to one physical table, with per-context UnitOfWork/IdentityMap, first-class MySQL & PostgreSQL support, and explicit per-slice optimistic locking.',
			logo: {
				src: './src/assets/logo.svg',
				replacesTitle: false,
			},
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/articulate-orm/core' },
			],
			editLink: {
				baseUrl: 'https://github.com/articulate-orm/core/edit/main/docs-site/',
			},
			customCss: ['./src/styles/custom.css'],
			sidebar: [
				{
					label: 'Start Here',
					items: [
						{ label: 'Why Articulate?', slug: 'index' },
						{ label: 'Getting Started', slug: 'guides/getting-started' },
					],
				},
				{
					label: 'Core Concepts',
					items: [
						{ label: 'Context-Bounded Entities', slug: 'concepts/context-bounded-entities' },
						{ label: 'Unit of Work & Identity Map', slug: 'concepts/unit-of-work-identity-map' },
						{ label: 'Hydration & Proxies', slug: 'concepts/hydration-proxies' },
						{ label: 'Caching', slug: 'concepts/caching' },
					],
				},
				{
					label: 'Guides',
					items: [
						{ label: 'Entity Mapping', slug: 'guides/entity-mapping' },
						{ label: 'Migrations', slug: 'guides/migrations' },
						{ label: 'Relationships', slug: 'guides/relationships' },
						{ label: 'Query Builder', slug: 'guides/query-builder' },
						{ label: 'Pagination & Filtering', slug: 'guides/pagination-filtering' },
						{ label: 'Lifecycle Callbacks', slug: 'guides/lifecycle-callbacks' },
						{ label: 'Custom Types', slug: 'guides/custom-types' },
						{ label: 'Transactions & Locking', slug: 'guides/transactions-locking' },
						{ label: 'Optimistic Locking', slug: 'guides/optimistic-locking' },
						{ label: 'Performance', slug: 'guides/performance' },
						{ label: 'Known Limitations', slug: 'guides/known-limitations' },
					],
				},
				{
					label: 'Architecture',
					items: [
						{ label: 'Module Map', slug: 'architecture/module-map' },
						{ label: 'Boundaries & Conventions', slug: 'architecture/boundaries' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'ADR 0001: Per-Slice Version Guards', slug: 'reference/adr-0001-per-slice-version-guards' },
						{ label: 'Changelog', slug: 'reference/changelog' },
					],
				},
			],
		}),
	],
});
