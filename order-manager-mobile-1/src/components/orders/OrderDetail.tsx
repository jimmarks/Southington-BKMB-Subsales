import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

const OrderDetail = ({ route }) => {
    const { order } = route.params;

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Order Details</Text>
            <Text style={styles.label}>Name: {order.name}</Text>
            <Text style={styles.label}>Address: {order.address}</Text>
            <Text style={styles.label}>Phone: {order.phone}</Text>
            <Text style={styles.label}>Turkey Quantity: {order.turkeyQuantity}</Text>
            <Text style={styles.label}>Ham Quantity: {order.hamQuantity}</Text>
            <Text style={styles.label}>Combo Quantity: {order.comboQuantity}</Text>
            <Text style={styles.label}>Donation Amount: ${order.donationAmount}</Text>
            <Text style={styles.label}>Total Amount: ${order.totalAmount}</Text>
            <Text style={styles.label}>Payment Method: {order.paymentMethod}</Text>
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        padding: 20,
        backgroundColor: '#fff',
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 20,
    },
    label: {
        fontSize: 18,
        marginBottom: 10,
    },
});

export default OrderDetail;