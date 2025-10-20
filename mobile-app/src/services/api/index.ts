import axios from 'axios';

// Update this URL to match your WordPress site
const API_BASE_URL = process.env.WORDPRESS_API_URL || 'https://your-wordpress-site.com/wp-json/order-manager/v1';

// Create axios instance with default headers
const apiClient = axios.create({
    baseURL: API_BASE_URL,
    headers: {
        'Content-Type': 'application/json',
    },
});

// Add request interceptor to include team authentication headers
apiClient.interceptors.request.use(async (config) => {
    try {
        // Import AsyncStorage dynamically to avoid circular dependency
        const AsyncStorage = require('@react-native-async-storage/async-storage').default;
        
        const teamName = await AsyncStorage.getItem('teamName');
        const accessCode = await AsyncStorage.getItem('accessCode');
        
        if (teamName && accessCode) {
            config.headers['X-Team-Name'] = teamName;
            config.headers['X-Access-Code'] = accessCode;
        }
    } catch (error) {
        console.warn('Failed to add auth headers:', error);
    }
    
    return config;
});

export const placeOrder = async (orderData: any) => {
    try {
        const response = await apiClient.post('/orders', orderData);
        return response.data;
    } catch (error) {
        throw new Error('Error placing order: ' + (error as Error).message);
    }
};

export const getOrders = async () => {
    try {
        const response = await apiClient.get('/orders');
        return response.data;
    } catch (error) {
        throw new Error('Error fetching orders: ' + (error as Error).message);
    }
};

export const syncOrders = async (orders: any[]) => {
    try {
        // Send each order individually since the API expects single order creation
        const results = await Promise.all(
            orders.map(order => apiClient.post('/orders', order))
        );
        return results.map(result => result.data);
    } catch (error) {
        throw new Error('Error syncing orders: ' + (error as Error).message);
    }
};

export default apiClient;