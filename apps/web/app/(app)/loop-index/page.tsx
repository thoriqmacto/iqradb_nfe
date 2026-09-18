import { SHELL_X } from "@/lib/nav";
import { cn } from "@/lib/utils";

export const metadata = { title: "Loop Index" };

export default function LoopIndexPage() {
    return (
        <section className={cn("flex flex-col gap-4 py-6", SHELL_X)}>
            <h1 className="text-lg font-semibold tracking-tight">Loop Index</h1>
            <p className="text-sm text-muted-foreground">Nothing here yet.</p>
        </section>
    );
}
