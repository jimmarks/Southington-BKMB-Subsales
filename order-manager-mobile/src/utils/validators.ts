export const validateEmail = (email: string): boolean => {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
};

export const validatePassword = (password: string): boolean => {
    return password.length >= 6;
};

export const validateRequiredField = (field: string): boolean => {
    return field.trim() !== '';
};

export const validateOrderForm = (orderData: { item: string; quantity: number; donationAmount: number; paymentMethod: string }): boolean => {
    return validateRequiredField(orderData.item) &&
           orderData.quantity > 0 &&
           orderData.donationAmount >= 0 &&
           validateRequiredField(orderData.paymentMethod);
};