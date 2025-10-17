import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';

const API_URL = 'https://your-wordpress-site.com/wp-json/order-sync/v1';

export const syncOrdersWithWordPress = async (orders) => {
    try {
        const response = await axios.post(`${API_URL}/sync-orders`, { orders });
        return response.data;
    } catch (error) {
        console.error('Error syncing orders:', error);
        throw error;
    }
};

export const fetchOrdersFromWordPress = async () => {
    try {
        const response = await axios.get(`${API_URL}/get-orders`);
        return response.data;
    } catch (error) {
        console.error('Error fetching orders:', error);
        throw error;
    }
};

export const saveOrderLocally = async (order) => {
    try {
        const existingOrders = await AsyncStorage.getItem('orders');
        const orders = existingOrders ? JSON.parse(existingOrders) : [];
        orders.push(order);
        await AsyncStorage.setItem('orders', JSON.stringify(orders));
    } catch (error) {
        console.error('Error saving order locally:', error);
        throw error;
    }
};

export const getLocalOrders = async () => {
    try {
        const existingOrders = await AsyncStorage.getItem('orders');
        return existingOrders ? JSON.parse(existingOrders) : [];
    } catch (error) {
        console.error('Error retrieving local orders:', error);
        throw error;
    }
};