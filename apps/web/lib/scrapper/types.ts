/**
 * Shapes returned by /api/v1/scrapper/*.
 *
 * Note what is absent: there is no field anywhere here for the SCDB storage
 * state. The API never returns it, so the frontend has no type for it and no
 * way to render it by accident.
 */

export type SessionStatus = "unknown" | "valid" | "expired" | "invalid" | "error";

export type ScrapperSession = {
    host: string;
    status: SessionStatus;
    last_validated_at: string | null;
    last_validation_error: string | null;
    updated_at: string | null;
};

export type LocatorStrategy = "role" | "label" | "text" | "placeholder" | "testId" | "css";

export type RecipeLocator = {
    strategy: LocatorStrategy;
    role?: string;
    name?: string;
    label?: string;
    text?: string;
    placeholder?: string;
    testId?: string;
    css?: string;
    exact?: boolean;
    nth?: number;
};

export type RecipeActionType =
    | "goto"
    | "click"
    | "fill"
    | "selectOption"
    | "press"
    | "waitForURL"
    | "waitForLoadState"
    | "waitForVisible"
    | "download";

export type RecipeAction = {
    type: RecipeActionType;
    locator?: RecipeLocator;
    value?: string;
    url?: string;
    state?: "load" | "domcontentloaded" | "networkidle";
    timeoutMs?: number;
};

export type Recipe = {
    id: string;
    name: string;
    description: string | null;
    start_url: string;
    dataset_key: string;
    enabled: boolean;
    expected_file_type: string;
    expected_filename_pattern: string | null;
    schema_version: number;
    actions: RecipeAction[];
    import_config: Record<string, unknown> | null;
    has_codegen_source: boolean;
    last_success_run_at: string | null;
    last_failed_run_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type RunMode = "test_navigation" | "download" | "import";

export type RunStatus =
    | "queued"
    | "starting_browser"
    | "validating_session"
    | "navigating"
    | "waiting_for_report"
    | "downloading"
    | "downloaded"
    | "parsing"
    | "validating"
    | "staged"
    | "importing"
    | "completed"
    | "failed"
    | "session_expired"
    | "cancelled";

export type RunImport = {
    id: string;
    status: string;
    dataset_key: string;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    inserted: number;
    updated: number;
    unchanged: number;
    rejected: number;
    error_message: string | null;
};

export type Run = {
    id: string;
    recipe: { id?: string; name?: string; dataset_key?: string };
    mode: RunMode;
    status: RunStatus;
    status_label: string;
    is_terminal: boolean;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    downloaded_filename: string | null;
    checksum: string | null;
    file_size: number | null;
    error_code: string | null;
    error_message: string | null;
    failed_step_index: number | null;
    failed_action: RecipeAction | null;
    final_url: string | null;
    has_screenshot: boolean;
    has_trace: boolean;
    import: RunImport | null;
    created_at: string | null;
};

export type PreviewRow = {
    row_number: number;
    status: string;
    unique_key: string | null;
    values: Record<string, string>;
    errors: string[];
};

export type Preview = {
    source: "staging" | "file";
    headers: string[];
    total_rows: number | null;
    valid_rows: number | null;
    invalid_rows: number | null;
    status: string | null;
    rows: PreviewRow[];
};

export type UnsupportedCodegenLine = {
    line: number;
    source: string;
    reason: string;
};

export type CodegenParseResult = {
    actions: RecipeAction[];
    unsupported: UnsupportedCodegenLine[];
    summary: { converted: number; unsupported: number };
};
