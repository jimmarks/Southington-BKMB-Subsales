import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useSelector } from 'react-redux';
import { RootState } from '../store';
import OrderDetail from '../components/orders/OrderDetail';

const OrderScreen = ({ route }) => {
    const { orderId } = route.params;
    const order = useSelector((state: RootState) => state.orders.orders.find(o => o.id === orderId));

    if (!order) {
        return (
            <View style={styles.container}>
                <Text style={styles.errorText}>Order not found</Text>
            </View>
        );
    }

    return (
        <View style={styles.container}>
            <OrderDetail order={order} />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        padding: 16,
        backgroundColor: '#fff',
    },
    errorText: {
        color: 'red',
        textAlign: 'center',
        marginTop: 20,
    },
});

export default OrderScreen;