import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { SHELL_X } from "@/lib/nav";
import { cn } from "@/lib/utils";

export const metadata = { title: "Dashboard" };

type TrainStat = {
    label: string;
    done: number;
    total: number;
};

type TrainProgress = {
    train: string;
    progress: number;
    stats: TrainStat[];
};

/**
 * Placeholder figures. Replace with a real `/api/v1/trains/progress` fetch once
 * the data model exists — the card markup below expects the same shape.
 */
const TRAINS: TrainProgress[] = [
    {
        train: "Train-8",
        progress: 92,
        stats: [
            { label: "Loops", done: 184, total: 196 },
            { label: "SAT", done: 171, total: 196 },
            { label: "Packages", done: 22, total: 24 },
            { label: "Milestones", done: 11, total: 12 },
        ],
    },
    {
        train: "Train-9",
        progress: 74,
        stats: [
            { label: "Loops", done: 149, total: 203 },
            { label: "SAT", done: 128, total: 203 },
            { label: "Packages", done: 17, total: 25 },
            { label: "Milestones", done: 9, total: 12 },
        ],
    },
    {
        train: "Train-10",
        progress: 48,
        stats: [
            { label: "Loops", done: 94, total: 198 },
            { label: "SAT", done: 71, total: 198 },
            { label: "Packages", done: 11, total: 24 },
            { label: "Milestones", done: 5, total: 12 },
        ],
    },
    {
        train: "Train-11",
        progress: 21,
        stats: [
            { label: "Loops", done: 43, total: 207 },
            { label: "SAT", done: 18, total: 207 },
            { label: "Packages", done: 4, total: 26 },
            { label: "Milestones", done: 2, total: 12 },
        ],
    },
];

function ProgressBar({ value }: { value: number }) {
    return (
        <div
            role="progressbar"
            aria-valuenow={value}
            aria-valuemin={0}
            aria-valuemax={100}
            className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full bg-primary transition-[width]"
                style={{ width: `${value}%` }}
            />
        </div>
    );
}

function TrainCard({ train, progress, stats }: TrainProgress) {
    return (
        <Card className="gap-4 py-4">
            <CardHeader className="px-4">
                <CardTitle className="flex items-baseline justify-between">
                    <span className="text-sm font-medium text-muted-foreground">
                        {train}
                    </span>
                    <span className="text-2xl font-semibold tabular-nums">
                        {progress}%
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">
                <ProgressBar value={progress} />
                <dl className="grid grid-cols-2 gap-x-8 gap-y-1.5 text-sm">
                    {stats.map((stat) => (
                        <div key={stat.label} className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">{stat.label}</dt>
                            <dd className="tabular-nums">
                                {stat.done}
                                <span className="text-muted-foreground">/{stat.total}</span>
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

export default function DashboardPage() {
    return (
        <section className={cn("flex flex-col gap-4 py-6", SHELL_X)}>
            <h1 className="text-lg font-semibold tracking-tight">Dashboard</h1>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {TRAINS.map((train) => (
                    <TrainCard key={train.train} {...train} />
                ))}
            </div>
        </section>
    );
}
