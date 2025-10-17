import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import OrderForm from '../components/orders/OrderForm';

const OrderScreen = () => {
    return (
        <View style={styles.container}>
            <Text style={styles.title}>Place a New Order</Text>
            <OrderForm />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        padding: 16,
        backgroundColor: '#fff',
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 16,
    },
});

export default OrderScreen;