/**
 * Main navigation — single source of truth.
 *
 * Adding an entry here is not enough on its own: also create the page under
 * `app/(app)/<slug>/` and add the prefix to `PROTECTED_PREFIXES` and
 * `config.matcher` in `middleware.ts`.
 */
export type NavItem = {
    href: string;
    label: string;
};

export const MAIN_NAV: NavItem[] = [
    { href: "/dashboard", label: "Dashboard" },
    { href: "/loop-index", label: "Loop Index" },
    { href: "/sat", label: "SAT" },
    { href: "/package", label: "Package" },
    { href: "/milestone", label: "Milestone" },
];

/**
 * Horizontal padding + full-bleed width shared by the top bar and every page
 * shell, so content lines up with the nav on every breakpoint. No max-width:
 * wide tables get the whole viewport on desktop.
 */
export const SHELL_X = "w-full px-4 sm:px-6 lg:px-8";
