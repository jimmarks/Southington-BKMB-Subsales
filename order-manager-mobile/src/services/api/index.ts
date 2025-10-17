import axios from 'axios';

const API_BASE_URL = 'https://your-wordpress-site.com/wp-json'; // Replace with your WordPress site URL

// Function to handle user login
export const loginUser = async (credentials) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/jwt-auth/v1/token`, credentials);
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};

// Function to handle user registration
export const registerUser = async (userData) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/wp/v2/users`, userData);
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};

// Function to fetch orders
export const fetchOrders = async (token) => {
    try {
        const response = await axios.get(`${API_BASE_URL}/wp/v2/orders`, {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};

// Function to create a new order
export const createOrder = async (orderData, token) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/wp/v2/orders`, orderData, {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};

// Function to synchronize orders with WordPress
export const syncOrders = async (orders, token) => {
    try {
        const response = await axios.post(`${API_BASE_URL}/wp/v2/orders/sync`, { orders }, {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};

// Function to fetch reports
export const fetchReports = async (token) => {
    try {
        const response = await axios.get(`${API_BASE_URL}/wp/v2/reports`, {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });
        return response.data;
    } catch (error) {
        throw error.response.data;
    }
};