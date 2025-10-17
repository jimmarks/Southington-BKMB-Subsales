import AsyncStorage from '@react-native-async-storage/async-storage';
import { Order } from '../types';
import { api } from './api';

const WP_API_URL = 'https://your-wordpress-site.com/wp-json/your-endpoint';

export const syncOrdersWithWordPress = async () => {
    try {
        const orders = await AsyncStorage.getItem('pendingOrders');
        if (orders) {
            const parsedOrders: Order[] = JSON.parse(orders);
            await Promise.all(parsedOrders.map(order => api.post(`${WP_API_URL}/orders`, order)));
            await AsyncStorage.removeItem('pendingOrders');
        }
    } catch (error) {
        console.error('Error syncing orders with WordPress:', error);
    }
};

export const saveOrderLocally = async (order: Order) => {
    try {
        const existingOrders = await AsyncStorage.getItem('pendingOrders');
        const orders = existingOrders ? JSON.parse(existingOrders) : [];
        orders.push(order);
        await AsyncStorage.setItem('pendingOrders', JSON.stringify(orders));
    } catch (error) {
        console.error('Error saving order locally:', error);
    }
};