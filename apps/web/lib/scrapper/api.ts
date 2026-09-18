import { AxiosError } from "axios";
import { api } from "@/lib/api";
import type {
    CodegenParseResult,
    Preview,
    Recipe,
    Run,
    RunMode,
    ScrapperSession,
} from "./types";

/**
 * All Scrapper HTTP calls go through the shared axios instance in `@/lib/api`,
 * so auth headers and 401 handling stay consistent. Nothing here imports axios
 * directly — see the repo's HTTP client rule in CLAUDE.md.
 */

/** SWR fetcher: `useSWR("/scrapper/session", fetcher)`. */
export async function fetcher<T>(url: string): Promise<T> {
    const { data } = await api.get<T>(url);
    return data;
}

/** SWR fetcher for endpoints that wrap their payload in `data`. */
export async function dataFetcher<T>(url: string): Promise<T> {
    const { data } = await api.get<{ data: T }>(url);
    return data.data;
}

export function readError(err: unknown, fallback: string): string {
    const ax = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    const first = ax?.response?.data?.errors
        ? Object.values(ax.response.data.errors).flat()[0]
        : undefined;
    return first ?? ax?.response?.data?.message ?? fallback;
}

export const SESSION_KEY = "/scrapper/session";
export const RECIPES_KEY = "/scrapper/recipes";
export const RUNS_KEY = "/scrapper/runs";

export async function fetchSession(): Promise<{ session: ScrapperSession | null }> {
    return fetcher(SESSION_KEY);
}

/**
 * Upload a Playwright storage state.
 *
 * The file's text is sent once and never comes back — there is no GET that
 * returns it. The caller should drop its copy immediately after this resolves.
 */
export async function uploadSession(storageState: string) {
    const { data } = await api.post<{ session: ScrapperSession; message: string }>(
        SESSION_KEY,
        { storage_state: storageState },
    );
    return data;
}

export async function validateSession() {
    const { data } = await api.post<{
        session: ScrapperSession | null;
        result: string;
        message: string | null;
    }>(`${SESSION_KEY}/validate`);
    return data;
}

export async function deleteSession() {
    await api.delete(SESSION_KEY);
}

export async function fetchRecipes(): Promise<Recipe[]> {
    return dataFetcher<Recipe[]>(RECIPES_KEY);
}

export type RecipeInput = {
    name: string;
    description?: string | null;
    start_url: string;
    dataset_key: string;
    enabled?: boolean;
    expected_filename_pattern?: string | null;
    actions: Recipe["actions"];
    codegen_source?: string | null;
};

export async function createRecipe(input: RecipeInput): Promise<Recipe> {
    const { data } = await api.post<{ data: Recipe }>(RECIPES_KEY, input);
    return data.data;
}

export async function updateRecipe(id: string, input: Partial<RecipeInput>): Promise<Recipe> {
    const { data } = await api.patch<{ data: Recipe }>(`${RECIPES_KEY}/${id}`, input);
    return data.data;
}

export async function deleteRecipe(id: string): Promise<void> {
    await api.delete(`${RECIPES_KEY}/${id}`);
}

/** Returns 202 — the run is queued, not finished. Poll `fetchRun`. */
export async function startRun(recipeId: string, mode: RunMode): Promise<Run> {
    const { data } = await api.post<{ data: Run }>(`${RECIPES_KEY}/${recipeId}/runs`, { mode });
    return data.data;
}

export async function fetchRuns(): Promise<Run[]> {
    return dataFetcher<Run[]>(RUNS_KEY);
}

export async function fetchRun(id: string): Promise<Run> {
    return dataFetcher<Run>(`${RUNS_KEY}/${id}`);
}

export async function fetchPreview(runId: string): Promise<Preview> {
    return fetcher<Preview>(`${RUNS_KEY}/${runId}/preview`);
}

/**
 * Convert pasted Codegen text to structured actions.
 *
 * The conversion happens server-side by string matching — the paste is never
 * executed, here or there.
 */
export async function parseCodegen(source: string): Promise<CodegenParseResult> {
    const { data } = await api.post<CodegenParseResult>("/scrapper/codegen/parse", { source });
    return data;
}
