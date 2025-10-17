import { API_URL } from '../../config'; // Assuming you have a config file for API_URL
import axios from 'axios';

// Function to fetch all orders
export const fetchOrders = async () => {
    try {
        const response = await axios.get(`${API_URL}/orders`);
        return response.data;
    } catch (error) {
        throw new Error('Error fetching orders: ' + error.message);
    }
};

// Function to create a new order
export const createOrder = async (orderData) => {
    try {
        const response = await axios.post(`${API_URL}/orders`, orderData);
        return response.data;
    } catch (error) {
        throw new Error('Error creating order: ' + error.message);
    }
};

// Function to update an existing order
export const updateOrder = async (orderId, orderData) => {
    try {
        const response = await axios.put(`${API_URL}/orders/${orderId}`, orderData);
        return response.data;
    } catch (error) {
        throw new Error('Error updating order: ' + error.message);
    }
};

// Function to delete an order
export const deleteOrder = async (orderId) => {
    try {
        await axios.delete(`${API_URL}/orders/${orderId}`);
    } catch (error) {
        throw new Error('Error deleting order: ' + error.message);
    }
};