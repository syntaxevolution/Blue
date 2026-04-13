export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export interface BrokenItemPayload {
    key: string;
    name: string;
    repair_cost_barrels: number;
    player_barrels: number;
}

export interface PlayerBalance {
    cash: number;
    barrels: number;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        requires_username_claim?: boolean;
        broken_item_key?: string | null;
        broken_item?: BrokenItemPayload | null;
        unread_activity_count?: number;
        unread_hostility_count?: number;
        player_balance?: PlayerBalance | null;
    };
};
