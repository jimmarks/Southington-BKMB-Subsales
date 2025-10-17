import axios from 'axios';

const API_BASE_URL = 'https://your-wordpress-site.com/wp-json/order-manager/v1';

export const placeOrder = async (orderData) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/orders`, orderData);
        return response.data;
    } catch (error) {
        throw new Error('Error placing order: ' + error.message);
    }
};

export const getOrders = async () => {
    try {
        const response = await axios.get(`${API_BASE_URL}/orders`);
        return response.data;
    } catch (error) {
        throw new Error('Error fetching orders: ' + error.message);
    }
};

export const syncOrders = async (orders) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/sync`, { orders });
        return response.data;
    } catch (error) {
        throw new Error('Error syncing orders: ' + error.message);
    }
};