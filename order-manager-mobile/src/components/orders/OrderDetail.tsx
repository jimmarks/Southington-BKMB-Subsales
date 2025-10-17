import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

const OrderDetail = ({ route }) => {
    const { order } = route.params;

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Order Details</Text>
            <Text style={styles.label}>Order ID:</Text>
            <Text style={styles.value}>{order.id}</Text>
            <Text style={styles.label}>Items:</Text>
            {order.items.map((item, index) => (
                <Text key={index} style={styles.value}>
                    {item.name} - Quantity: {item.quantity}
                </Text>
            ))}
            <Text style={styles.label}>Total Amount:</Text>
            <Text style={styles.value}>${order.totalAmount.toFixed(2)}</Text>
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
        fontSize: 16,
        fontWeight: '600',
        marginTop: 10,
    },
    value: {
        fontSize: 16,
        marginBottom: 5,
    },
});

export default OrderDetail;