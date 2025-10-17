export interface Order {
    id: string;
    name: string;
    address: string;
    phoneNumber: string;
    turkeyQuantity: number;
    hamQuantity: number;
    comboQuantity: number;
    donationAmount: number;
    totalAmount: number;
    paymentMethod: 'cash' | 'check';
}

export interface User {
    code: string;
    teamName: string;
}

export interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
}