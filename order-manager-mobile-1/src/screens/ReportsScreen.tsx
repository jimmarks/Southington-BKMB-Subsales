import React from 'react';
import { View, Text, StyleSheet, FlatList } from 'react-native';
import { useSelector } from 'react-redux';
import { RootState } from '../store';

const ReportsScreen = () => {
    const orders = useSelector((state: RootState) => state.orders.orders);

    const renderItem = ({ item }: { item: any }) => (
        <View style={styles.orderItem}>
            <Text style={styles.orderText}>Name: {item.name}</Text>
            <Text style={styles.orderText}>Address: {item.address}</Text>
            <Text style={styles.orderText}>Phone: {item.phone}</Text>
            <Text style={styles.orderText}>Turkey: {item.turkeyQuantity}</Text>
            <Text style={styles.orderText}>Ham: {item.hamQuantity}</Text>
            <Text style={styles.orderText}>Combo: {item.comboQuantity}</Text>
            <Text style={styles.orderText}>Donation: ${item.donationAmount}</Text>
            <Text style={styles.orderText}>Total: ${item.totalAmount}</Text>
        </View>
    );

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Order Reports</Text>
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
        backgroundColor: '#fff',
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 16,
    },
    orderItem: {
        padding: 10,
        borderBottomWidth: 1,
        borderBottomColor: '#ccc',
    },
    orderText: {
        fontSize: 16,
    },
});

export default ReportsScreen;