import Link from "next/link";
import { Button } from "@/components/ui/button";
import { APP_NAME } from "@/lib/env";

export default function LandingPage() {
    return (
        <section className="mx-auto flex w-full max-w-5xl flex-col gap-10 px-4 py-16 md:py-24">
            <div className="flex flex-col gap-4">
                <h1 className="text-4xl font-semibold tracking-tight md:text-5xl">
                    {APP_NAME}
                </h1>
                <p className="max-w-2xl text-muted-foreground">
                    Sign in to track loop index, SAT, package and milestone progress
                    across the trains.
                </p>
            </div>

            <div className="flex flex-wrap gap-3">
                <Button asChild>
                    <Link href="/login">Sign in</Link>
                </Button>
                <Button asChild variant="outline">
                    <Link href="/register">Create account</Link>
                </Button>
            </div>
        </section>
    );
}
