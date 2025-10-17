export interface User {
    id: string;
    username: string;
    email: string;
    token: string;
}

export interface Order {
    id: string;
    userId: string;
    items: OrderItem[];
    totalAmount: number;
    donationAmount?: number;
    paymentMethod: string;
    createdAt: string;
}

export interface OrderItem {
    productId: string;
    quantity: number;
}

export interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
}

export interface Location {
    latitude: number;
    longitude: number;
    address: string;
}