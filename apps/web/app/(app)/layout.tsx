"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/components/auth-provider";
import { AccountMenu } from "@/components/account-menu";
import { MainNav } from "@/components/main-nav";
import { APP_NAME } from "@/lib/env";
import { SHELL_X } from "@/lib/nav";
import { cn } from "@/lib/utils";

export default function AppLayout({ children }: { children: React.ReactNode }) {
    const { status } = useAuth();
    const router = useRouter();

    useEffect(() => {
        if (status === "anonymous") {
            router.replace("/login");
        }
    }, [status, router]);

    if (status !== "authenticated") {
        return (
            <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">
                Loading…
            </div>
        );
    }

    return (
        <div className="flex min-h-screen flex-col">
            <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                <div className={cn("flex h-12 items-center gap-4", SHELL_X)}>
                    <Link
                        href="/dashboard"
                        className="shrink-0 text-sm font-semibold tracking-tight"
                    >
                        {APP_NAME}
                    </Link>
                    <div className="min-w-0 flex-1">
                        <MainNav />
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <main className="flex-1">{children}</main>
        </div>
    );
}
