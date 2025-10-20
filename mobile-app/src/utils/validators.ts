export const validateOrderInput = (name: string, address: string, phone: string, turkeyQty: number, hamQty: number, comboQty: number): string | null => {
    if (!name) {
        return "Name is required.";
    }
    if (!address) {
        return "Address is required.";
    }
    if (!phone) {
        return "Phone number is required.";
    }
    if (turkeyQty < 0 || hamQty < 0 || comboQty < 0) {
        return "Quantities cannot be negative.";
    }
    return null;
};

export const validateDonationAmount = (donation: number): string | null => {
    if (donation < 0) {
        return "Donation amount cannot be negative.";
    }
    return null;
};