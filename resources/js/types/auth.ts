export type User = {
    id: number;
    name: string;
    email: string;
    role?: string;
    role_label?: string;
    avatar?: string;
    created_at?: string;
    updated_at?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type NavigationItem = {
    key: string;
    title: string;
    href: string;
    available: boolean;
};
