import AsyncStorage from '@react-native-async-storage/async-storage';
import { Order } from '../types';
import apiClient from './api';

// Use the configured axios instance in src/services/api/index.ts
export const syncOrdersWithWordPress = async () => {
    try {
        const orders = await AsyncStorage.getItem('pendingOrders');
        if (orders) {
            const parsedOrders: Order[] = JSON.parse(orders);
            // apiClient is already configured with the correct baseURL and auth headers
            await Promise.all(parsedOrders.map(order => apiClient.post(`/orders`, order)));
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