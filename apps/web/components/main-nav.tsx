"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { MAIN_NAV } from "@/lib/nav";

export function MainNav() {
    const pathname = usePathname();

    return (
        <nav
            aria-label="Main"
            className="flex items-center gap-1 overflow-x-auto text-sm [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            {MAIN_NAV.map((item) => {
                const active =
                    pathname === item.href || pathname.startsWith(`${item.href}/`);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? "page" : undefined}
                        className={cn(
                            "rounded-md px-2.5 py-1.5 whitespace-nowrap transition-colors",
                            active
                                ? "bg-accent text-accent-foreground font-medium"
                                : "text-muted-foreground hover:text-foreground hover:bg-accent/50",
                        )}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
