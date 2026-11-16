export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';
export type SubmissionState = 'pending' | 'accepted' | 'uncertain' | 'rejected';

export type GenerationSummary = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    submission_state: SubmissionState;
    image_url: string | null;
    image_url_expires_at: string | null;
    estimate: string;
    user_charge: string | null;
    error: string | null;
    created_at: string;
};

export type Generation = GenerationSummary & {
    reserved: string;
    started_at: string | null;
    completed_at: string | null;
    usage: Record<string, number> | null;
};

export type GenerationPageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type GenerationFeed = {
    data: GenerationSummary[];
};

export type GenerationPaginator = GenerationFeed & {
    total: number;
    from: number | null;
    to: number | null;
    links: GenerationPageLink[];
};

export type GenerationStatusOption = {
    value: GenerationStatus;
    label: string;
};
