import AsyncStorage from '@react-native-async-storage/async-storage';
import { Order } from '../types';
import api from './api';

const ORDER_STORAGE_KEY = '@orders';

export const saveOrderLocally = async (order: Order) => {
    try {
        const existingOrders = await AsyncStorage.getItem(ORDER_STORAGE_KEY);
        const orders = existingOrders ? JSON.parse(existingOrders) : [];
        orders.push(order);
        await AsyncStorage.setItem(ORDER_STORAGE_KEY, JSON.stringify(orders));
    } catch (error) {
        console.error('Error saving order locally:', error);
    }
};

export const syncOrdersWithBackend = async () => {
    try {
        const existingOrders = await AsyncStorage.getItem(ORDER_STORAGE_KEY);
        if (existingOrders) {
            const orders = JSON.parse(existingOrders);
            for (const order of orders) {
                await api.placeOrder(order);
            }
            await AsyncStorage.removeItem(ORDER_STORAGE_KEY);
        }
    } catch (error) {
        console.error('Error syncing orders with backend:', error);
    }
};

export const calculateTotalAmount = (turkeyQty: number, hamQty: number, comboQty: number, donationAmount: number) => {
    const unitPrice = 10;
    const totalItems = turkeyQty + hamQty + comboQty;
    const totalAmount = (totalItems * unitPrice) + donationAmount;
    return totalAmount;
};