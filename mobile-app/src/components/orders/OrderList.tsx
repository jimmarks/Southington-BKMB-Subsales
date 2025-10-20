import React from 'react';
import { View, Text, FlatList, StyleSheet } from 'react-native';
import { useSelector } from 'react-redux';
import { RootState } from '../../store';

const OrderList = () => {
    const orders = useSelector((state: RootState) => state.orders.orders);

    const renderItem = ({ item }: { item: any }) => (
        <View style={styles.orderItem}>
            <Text style={styles.orderText}>Name: {item.name}</Text>
            <Text style={styles.orderText}>Address: {item.address}</Text>
            <Text style={styles.orderText}>Phone: {item.phone}</Text>
            <Text style={styles.orderText}>Turkey: {item.turkeyQty}</Text>
            <Text style={styles.orderText}>Ham: {item.hamQty}</Text>
            <Text style={styles.orderText}>Combo: {item.comboQty}</Text>
            <Text style={styles.orderText}>Donation: ${item.donation}</Text>
            <Text style={styles.orderText}>Total: ${item.total}</Text>
        </View>
    );

    return (
        <View style={styles.container}>
            <FlatList
                data={orders}
                renderItem={renderItem}
                keyExtractor={(item) => item.id.toString()}
            />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        padding: 16,
    },
    orderItem: {
        backgroundColor: '#f9f9f9',
        padding: 10,
        marginVertical: 8,
        borderRadius: 5,
    },
    orderText: {
        fontSize: 16,
    },
});

export default OrderList;